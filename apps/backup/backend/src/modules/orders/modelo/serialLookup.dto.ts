import { ApiProperty } from '@nestjs/swagger';

export class SerialLookupDto {
  @ApiProperty({ example: 123 })
  orderId!: number;

  @ApiProperty({ example: 10 })
  clientId!: number;

  @ApiProperty({ example: 2 })
  deviceTypeId!: number;

  @ApiProperty({ example: 'Samsung' })
  deviceBrand!: string;

  @ApiProperty({ example: 'A52' })
  deviceModel!: string;
}

