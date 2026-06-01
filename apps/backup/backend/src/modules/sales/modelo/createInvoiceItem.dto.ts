import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsIn, IsInt, IsNumber, IsOptional, IsString, Min, MinLength } from 'class-validator';

export class CreateInvoiceItemDto {
  @ApiProperty({ enum: ['manual', 'product', 'service'] })
  @IsIn(['manual', 'product', 'service'])
  itemType!: 'manual' | 'product' | 'service';

  @ApiPropertyOptional({ example: 123, nullable: true })
  @IsOptional()
  @IsInt()
  @Min(1)
  productId?: number | null;

  @ApiProperty({ example: 'Servicio de reparación' })
  @IsString()
  @MinLength(1)
  description!: string;

  @ApiProperty({ example: 1 })
  @IsNumber()
  @Min(0.01)
  quantity!: number;

  @ApiProperty({ example: 10000 })
  @IsNumber()
  @Min(0)
  unitPrice!: number;

  @ApiPropertyOptional({ example: 0 })
  @IsOptional()
  @IsNumber()
  @Min(0)
  taxPercent?: number;
}
