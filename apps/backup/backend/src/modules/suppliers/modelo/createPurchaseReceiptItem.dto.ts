import { ApiProperty } from '@nestjs/swagger';
import { IsInt, IsNumber, Min } from 'class-validator';

export class CreatePurchaseReceiptItemDto {
  @ApiProperty({ example: 1 })
  @IsInt()
  @Min(1)
  productId!: number;

  @ApiProperty({ example: 1 })
  @IsNumber()
  @Min(0.000001)
  quantity!: number;

  @ApiProperty({ example: 10 })
  @IsNumber()
  @Min(0)
  unitCost!: number;
}

