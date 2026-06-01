import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsArray, IsInt, IsOptional, IsString, Min, MinLength } from 'class-validator';
import { CreatePurchaseReceiptItemDto } from './createPurchaseReceiptItem.dto';

export class CreatePurchaseReceiptDto {
  @ApiProperty({ example: 1 })
  @IsInt()
  @Min(1)
  purchaseOrderId!: number;

  @ApiProperty({ example: '2026-01-01' })
  @IsString()
  @MinLength(1)
  receivedDate!: string;

  @ApiPropertyOptional({ example: '' })
  @IsOptional()
  @IsString()
  notes?: string;

  @ApiProperty({ type: [CreatePurchaseReceiptItemDto] })
  @IsArray()
  items!: CreatePurchaseReceiptItemDto[];
}

